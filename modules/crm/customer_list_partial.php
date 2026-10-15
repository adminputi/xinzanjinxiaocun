<?php
/**
 * 客户列表：取数 + 行渲染
 *
 * 为什么单独抽出来：列表改成「加载更多」之后，首屏（customers.php）和第 2、3…页
 * （ajax.php 的 customer_rows）走的是两套入口。如果各写一份查询和行模板，
 * 以后改了列表样式（列宽、按钮、跟进内容），接口返回的还是旧样子，两边必然对不上。
 * 这里统一成一份，两边都调它。
 */

// 搜索口径（搜哪些字段）和命中片段的渲染在 search_hit.php，导出 / 公海 / 跟进记录
// 用的也是同一份：加一个搜索字段只改那一处，不会出现各页搜出来的不是同一批客户
require_once __DIR__ . '/search_hit.php';

// 每页条数（= 每次点「加载更多」增加的条数）的三档约束：
// 默认 50（几百条数据下两屏到底，基本不用翻页），下限 10，上限 500
// ——再大一次要渲染上千行（每行 6 个按钮 + 图标），首屏会有肉眼可见的卡顿
if (!defined('CRM_CUSTOMER_PAGE_DEFAULT')) define('CRM_CUSTOMER_PAGE_DEFAULT', 50);
if (!defined('CRM_CUSTOMER_PAGE_MIN'))     define('CRM_CUSTOMER_PAGE_MIN', 10);
if (!defined('CRM_CUSTOMER_PAGE_MAX'))     define('CRM_CUSTOMER_PAGE_MAX', 500);

if (!function_exists('crm_customer_query')) {
    /**
     * 按当前筛选条件取一页客户
     *
     * @param PDO   $pdo
     * @param int   $userId  当前用户（置顶/标红是每人一套，必须带上）
     * @param bool  $isAdmin
     * @param array $get     $_GET（同一套参数，列表页和接口传的都是当前筛选条件）
     * @param int   $page
     * @param int   $perPage
     * @return array{list:array,total:int,pages:int,filters:array}
     */
    function crm_customer_query($pdo, $userId, $isAdmin, $get, $page, $perPage = 0)
    {
        $perPage = $perPage > 0 ? intval($perPage) : CRM_CUSTOMER_PAGE_SIZE;
        $page    = max(1, intval($page));
        $offset  = ($page - 1) * $perPage;

        $search        = trim((string)($get['search'] ?? ''));
        $sourceId      = intval($get['source_id'] ?? 0);
        $ownerId       = $isAdmin ? intval($get['owner_id'] ?? 0) : intval($userId);
        $intention     = $get['intention'] ?? '';
        $developedFrom = $get['developed_from'] ?? '';
        $developedTo   = $get['developed_to'] ?? '';
        $followFrom    = $get['follow_from'] ?? '';
        $followTo      = $get['follow_to'] ?? '';
        $deal          = $get['deal'] ?? '';        // ''全部 / '1'已成交 / '0'未成交
        $sort          = $get['sort'] ?? '';        // ''默认 / 'deal_amount' / 'last_deal' / 'last_follow'
        $flag          = $get['flag'] ?? '';        // ''全部 / 'pin'只看置顶 / 'mark'只看标红
        $showPool      = intval($get['pool'] ?? 0);

        // 置顶/标红是「每人各自一套」：JOIN 自己的那张标记表，别人置顶不影响我的顺序
        $flagJoin = customer_flag_join($userId);

        $where  = 'WHERE 1=1';
        $params = [];
        if ($search !== '') {
            // 搜索字段（名称/电话/公司/意向产品/备注/地址）统一在 crm_search_cond() 里定，
            // 导出、公海、跟进记录页共用同一份口径
            $sc     = crm_search_cond($search, 'c');
            $where .= " AND ({$sc['cond']})";
            $params = array_merge($params, $sc['params']);
        }
        if ($flag === 'pin')  { $where .= " AND IFNULL(f.is_pinned,0)=1"; }
        if ($flag === 'mark') { $where .= " AND IFNULL(f.is_marked,0)=1"; }
        if ($sourceId > 0) { $where .= " AND c.source_id=?"; $params[] = $sourceId; }
        if ($ownerId > 0)  { $where .= " AND c.owner_id=?";  $params[] = $ownerId; }
        if ($intention)    { $where .= " AND c.intention=?"; $params[] = $intention; }
        if ($developedFrom) { $where .= " AND c.developed_at>=?"; $params[] = $developedFrom; }
        if ($developedTo)   { $where .= " AND c.developed_at<=?"; $params[] = $developedTo; }
        if ($followFrom)    { $where .= " AND DATE(c.last_followed_at)>=?"; $params[] = $followFrom; }
        if ($followTo)      { $where .= " AND DATE(c.last_followed_at)<=?"; $params[] = $followTo; }
        if ($deal === '1') { $where .= " AND (" . sql_customer_is_deal('c') . ")"; }
        if ($deal === '0') { $where .= " AND NOT (" . sql_customer_is_deal('c') . ")"; }
        // 默认排除公海
        if (!$showPool) { $where .= " AND c.in_pool=0"; }

        // 先看既有的排序口径，再统一交给 customer_flag_order() 前置「置顶 → 标红」
        $baseOrder = 'c.id DESC';
        if ($sort === 'deal_amount') { $baseOrder = 'deal_amount DESC'; }
        elseif ($sort === 'last_deal') { $baseOrder = 'last_deal_date DESC'; }
        elseif ($sort === 'last_follow') { $baseOrder = 'c.last_followed_at DESC'; }
        $orderBy = customer_flag_order($baseOrder);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers c $flagJoin $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $pages = (int)ceil($total / $perPage);

        // 「最新一条跟进」不能写成每客户一个子查询（客户一多就是 N+1，页面必卡）。
        // 这里先按客户 GROUP BY 一次拿到跟进条数和「最新那条」的排序键，
        // 再整体 JOIN 回来：不管显示多少个客户，跟进表只扫一遍。
        $sql = "SELECT c.*, s.name as source_name, u.real_name as owner_name,
    IFNULL(f.is_pinned,0) as is_pinned, IFNULL(f.is_marked,0) as is_marked,
    IFNULL(agg.follow_cnt,0) as followup_count,
    lf.id as last_follow_id, lf.created_at as last_follow_at, lf.follow_type as last_follow_type,
    lf.content as last_follow_content, lf.result as last_follow_result,
    lf.next_follow_at as last_follow_next, fu.real_name as last_follow_user,
    IFNULL(c.intended_product,'') as intended_product,
    " . sql_customer_deal_stats('c') . "
    FROM customers c
    LEFT JOIN customer_sources s ON c.source_id=s.id
    LEFT JOIN users u ON c.owner_id=u.id
    LEFT JOIN (
        SELECT customer_id, COUNT(*) AS follow_cnt,
               MAX(CONCAT(created_at,'|',LPAD(id,10,'0'))) AS latest_key
        FROM customer_followups GROUP BY customer_id
    ) agg ON agg.customer_id = c.id
    LEFT JOIN customer_followups lf
           ON lf.customer_id = c.id
          AND CONCAT(lf.created_at,'|',LPAD(lf.id,10,'0')) = agg.latest_key
    LEFT JOIN users fu ON fu.id = lf.user_id
    $flagJoin
    $where $orderBy LIMIT $offset,$perPage";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $list = $stmt->fetchAll();

        // 顺便把前端「加载更多」要用的筛选回显值带回去（列表页表单、URL 都要用）
        $filters = [
            'search'         => $search,
            'source_id'      => $sourceId,
            'owner_id'       => $ownerId,
            'intention'      => $intention,
            'developed_from' => $developedFrom,
            'developed_to'   => $developedTo,
            'follow_from'    => $followFrom,
            'follow_to'      => $followTo,
            'deal'           => $deal,
            'sort'           => $sort,
            'flag'           => $flag,
            'pool'           => $showPool,
        ];

        return ['list' => $list, 'total' => $total, 'pages' => $pages, 'filters' => $filters];
    }
}

if (!function_exists('crm_render_customer_rows')) {
    /**
     * 渲染客户行的 <tr>（直接 echo；接口侧用 ob_start 捕获）
     *
     * 行的事件全部是 inline onclick，所以「加载更多」把返回的 HTML 直接插进 tbody
     * 就能用，不需要在 JS 里再写一遍这 10 列，也不需要重新绑定事件。
     */
    function crm_render_customer_rows($list, $userId, $isAdmin, $canViewFollowup = null, $search = '')
    {
        if ($canViewFollowup === null) {
            $canViewFollowup = check_permission('crm_followup_view');
        }
        $search = trim((string)$search);
        $resultBadge = ['已成交' => 'success', '有意向' => 'warning', '待跟进' => 'info', '无意向' => 'gray'];

        foreach ($list as $item):
            $isPinned = intval($item['is_pinned'] ?? 0) === 1;
            $isMarked = intval($item['is_marked'] ?? 0) === 1;
            // 只能标记自己归属的客户（管理员不限）——后端 ajax 同样会再校验一次，
            // 这里只是把按钮藏掉，避免别人点了才被告知没权限
            $canFlag = $isAdmin || intval($item['owner_id'] ?? 0) === intval($userId);
            $isDeal  = customer_is_deal($item);
            // 标红优先于已成交的浅绿底色：标红是「要重点处理」，比「已成交」更需要一眼看到。
            // 底色交给 CSS（tr.cust-marked / tr.cust-deal），JS 切换标红时只要增删 class
            $trClass = 'cust-row' . ($isMarked ? ' cust-marked' : '') . ($isDeal ? ' cust-deal' : '');
        ?>
<tr class="<?=$trClass?>" data-id="<?=$item['id']?>"<?= $isPinned ? ' data-pinned="1"' : '' ?>>
    <td><input type="checkbox" class="rowCheck" value="<?=$item['id']?>" onchange="syncCheck(this)"></td>
    <td>
        <?php // 置顶/标红图标做成常驻节点：点了标记不再整页刷新，靠 JS 切 display ?>
        <span class="cust-pin-icon" style="display:<?=$isPinned?'inline':'none'?>;"><i class="fa-solid fa-thumbtack" style="color:var(--primary);margin-right:4px;" title="已置顶"></i></span>
        <span class="cust-mark-icon" style="display:<?=$isMarked?'inline':'none'?>;"><i class="fa-solid fa-flag" style="color:var(--danger);margin-right:4px;" title="已标红"></i></span>
        <?php // 名称即使不命中也不变（高亮函数找不到关键词就只是原样转义）；
              // 命中时把关键词标黄，一眼确认是不是找对了人 ?>
        <a href="customer_detail.php?id=<?=$item['id']?>" class="cust-name"><?=crm_search_highlight($item['name'], $search)?></a><?php if ($isDeal): ?> <span class="badge badge-success" title="<?=htmlspecialchars(customer_deal_tip($item))?>">已成交</span><?php endif; ?>
        <?php // 列表不显示电话/公司/地址这些列（防旁边的人一眼看到名单），
              // 但搜索命中时，把命中的那个字段以片段形式临时挂在这里，电话打码 ?>
        <?=crm_search_hits_html($item, $search)?>
    </td>
    <?php
    // 跟进内容也算业务隐私：没有 crm_followup_view 的人不给看，也不给展开
    $lfContent = trim((string)($item['last_follow_content'] ?? ''));
    ?>
    <td>
    <?php if (!$canViewFollowup): ?>
        <span style="color:var(--gray-400);" title="你没有「CRM跟进查看」权限">—</span>
    <?php elseif ($lfContent === ''): ?>
        <span style="color:var(--gray-400);">暂无跟进</span>
    <?php else:
        $lfAt    = $item['last_follow_at'] ?? '';
        $lfDate  = $lfAt ? format_datetime_short($lfAt) : '';
        // 列表里只显示日期（到天）：带时分是 16 个字符，会把这一列的空间吃掉小半，
        // 完整到分钟的时间浮层和展开里都能看到
        $lfDay   = $lfAt ? date('Y-m-d', strtotime($lfAt)) : '';
        $lfNext  = $item['last_follow_next'] ? '下次跟进：' . format_datetime_short($item['last_follow_next']) : '无计划下次跟进';
        $tipText = ($item['last_follow_type'] ?? '') . ' · ' . ($item['last_follow_user'] ?? '') . ' · ' . $lfDate . "\n"
                 . "结果：" . ($item['last_follow_result'] ?? '') . "\n"
                 . $lfContent . "\n" . $lfNext;
    ?>
        <?php // 不再按字数截断：内容多少就显示多少，最多两行（-webkit-line-clamp:2），
              // 短内容自然只占一行，长内容第二行后省略，剩下的全文交给悬停浮层和展开 ?>
        <div class="follow-brief" data-cid="<?=$item['id']?>" data-cname="<?=htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8')?>"
             data-tip="<?=htmlspecialchars($tipText, ENT_QUOTES, 'UTF-8')?>"
             onclick="toggleFollowDetail(this)"
             style="cursor:pointer;display:flex;align-items:flex-start;gap:6px;">
            <span style="color:var(--gray-500);font-size:12px;flex-shrink:0;line-height:1.5;"><?=htmlspecialchars($lfDay)?></span>
            <span class="badge badge-<?=$resultBadge[$item['last_follow_result']] ?? 'info'?>" style="flex-shrink:0;margin-top:1px;"><?=htmlspecialchars($item['last_follow_result'] ?? '')?></span>
            <span class="follow-brief-text"><?=htmlspecialchars($lfContent)?></span>
        </div>
    <?php endif; ?>
    </td>
    <td><?=htmlspecialchars($item['source_name'])?:($item['source_id']?'-':'')?></td>
    <td><?php if($item['intention']): ?><span class="badge badge-<?=$item['intention']=='高'?'success':($item['intention']=='中'?'warning':'info')?>"><?=$item['intention']?></span><?php else: ?>-<?php endif; ?></td>
    <td><?php $prod=$item['intended_product']??''; if($prod): ?><span title="<?=htmlspecialchars($prod)?>" style="cursor:help;"><?=htmlspecialchars(mb_strlen($prod)>8?mb_substr($prod,0,8).'...':$prod)?></span><?php else: ?>-<?php endif; ?></td>
    <td><?=htmlspecialchars($item['owner_name']?:'-')?></td>
    <td><?=$item['followup_count']?>次</td>
    <td><?=($item['developed_at'] ?? '') ?: '--'?></td>
    <td>
        <?php if ($canFlag): ?>
        <button class="btn btn-sm btn-outline btn-pin" onclick="toggleFlag(<?=$item['id']?>,'pin',<?=$isPinned?0:1?>)" style="color:<?=$isPinned?'var(--primary)':'var(--gray-500)'?>;" title="<?=$isPinned?'取消置顶':'置顶到列表最前'?>"><i class="fa-solid fa-thumbtack"></i></button>
        <button class="btn btn-sm btn-outline btn-mark" onclick="toggleFlag(<?=$item['id']?>,'mark',<?=$isMarked?0:1?>)" style="color:<?=$isMarked?'var(--danger)':'var(--gray-500)'?>;" title="<?=$isMarked?'取消标红':'标红重点跟进'?>"><i class="fa-solid fa-flag"></i></button>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline" onclick="editCustomer(<?=$item['id']?>)" title="编辑"><i class="fa-solid fa-pen"></i></button>
        <button class="btn btn-sm btn-primary" onclick="showFollowupModal(<?=$item['id']?>,'<?=htmlspecialchars(addslashes($item['name']))?>')" title="跟进"><i class="fa-solid fa-comment-dots"></i></button>
        <button class="btn btn-sm btn-outline" onclick="showTransferModal(<?=$item['id']?>,'<?=htmlspecialchars(addslashes($item['name']))?>')" title="转移"><i class="fa-solid fa-arrow-right-arrow-left"></i></button>
        <button class="btn btn-sm btn-outline" style="color:var(--warning);" onclick="toPool(<?=$item['id']?>,'<?=htmlspecialchars(addslashes($item['name']))?>')" title="归入公海"><i class="fa-solid fa-water"></i></button>
    </td>
</tr>
<?php
        endforeach;
    }
}
